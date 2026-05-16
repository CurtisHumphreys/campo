import shutil, os
src = '/home/admin/site_allocation_review.csv'
dst = '/opt/forgebox/uploads/site_allocation_review.csv'
shutil.copy2(src, dst)
os.chmod(dst, 0o644)
print(f'Copied to {dst}')
